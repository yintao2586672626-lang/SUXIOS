import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { WSClient, generateReqId } from '@wecom/aibot-node-sdk';
import {
  createWecomAibotMessageHandler,
  createWecomAibotShutdown,
  wecomAibotCredentialError,
} from './lib/wecom_aibot_delivery.mjs';

const SDK_VERSION = '1.0.7';
const repoRoot = path.resolve(import.meta.dirname, '..');
const statePath = path.join(repoRoot, 'runtime', 'wecom-aibot-state.json');
const botId = String(process.env.SUXIOS_WECOM_AIBOT_ID || '').trim();
const secret = String(process.env.SUXIOS_WECOM_AIBOT_SECRET || '').trim();
const relayToken = String(process.env.SUXIOS_WECOM_AIBOT_RELAY_TOKEN || '').trim();
const apiBase = String(process.env.SUXIOS_LOCAL_API_BASE || 'http://127.0.0.1:8080').replace(/\/+$/, '');

let state = {
  contract_version: 'wecom_aibot_worker_state.v1',
  status: 'starting',
  authenticated: false,
  pid: process.pid,
  sdk_version: SDK_VERSION,
  updated_at: new Date().toISOString(),
  last_event_at: null,
  last_delivery_status: null,
};

const writeState = (patch = {}) => {
  state = { ...state, ...patch, updated_at: new Date().toISOString() };
  fs.mkdirSync(path.dirname(statePath), { recursive: true });
  const temp = `${statePath}.${process.pid}.tmp`;
  fs.writeFileSync(temp, JSON.stringify(state), { encoding: 'utf8', mode: 0o600 });
  fs.renameSync(temp, statePath);
};

const stopWithoutCredentials = () => {
  const errorCode = wecomAibotCredentialError(botId, secret, relayToken);
  writeState({
    status: 'blocked_not_configured',
    authenticated: false,
    error_code: errorCode,
  });
  process.exitCode = 2;
};

if (wecomAibotCredentialError(botId, secret, relayToken) !== null) {
  stopWithoutCredentials();
} else {
  const relay = async (endpoint, body) => {
    const response = await fetch(`${apiBase}${endpoint}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-SUXIOS-Relay-Token': relayToken,
      },
      body: JSON.stringify(body),
      signal: AbortSignal.timeout(20_000),
    });
    const payload = await response.json().catch(() => null);
    if (!response.ok || !payload || payload.code !== 200) {
      const error = new Error('local_relay_rejected');
      error.status = response.status;
      throw error;
    }
    return payload.data;
  };

  const logger = {
    debug() {},
    info() {},
    warn() { writeState({ status: state.authenticated ? 'authenticated' : 'connecting' }); },
    error() { writeState({ status: 'connection_error', authenticated: false, error_code: 'wecom_aibot_connection_error' }); },
  };
  const client = new WSClient({
    botId,
    secret,
    maxReconnectAttempts: -1,
    maxAuthFailureAttempts: 5,
    logger,
  });

  const recordDelivery = async (eventId, status, reference) => {
    if (!Number.isInteger(eventId) || eventId <= 0) return;
    try {
      await relay(`/api/internal/wecom-aibot/events/${eventId}/delivery`, { status, reference });
      writeState({ last_delivery_status: status });
    } catch {
      writeState({ last_delivery_status: 'receipt_store_failed' });
    }
  };

  const handleMessage = createWecomAibotMessageHandler({
    botId,
    relay,
    replyStream: client.replyStream.bind(client),
    requestId: () => generateReqId('suxios'),
    recordDelivery,
    writeState,
  });

  client.on('connected', () => writeState({ status: 'connected', authenticated: false, error_code: null }));
  client.on('authenticated', () => writeState({ status: 'authenticated', authenticated: true, error_code: null }));
  client.on('disconnected', () => writeState({ status: 'disconnected', authenticated: false }));
  client.on('reconnecting', () => writeState({ status: 'reconnecting', authenticated: false }));
  client.on('message.text', (frame) => { void handleMessage(frame, 'text'); });
  client.on('message.voice', (frame) => { void handleMessage(frame, 'voice'); });
  client.on('message.image', () => writeState({ last_event_at: new Date().toISOString(), last_delivery_status: 'image_not_ingested' }));
  client.on('message.file', () => writeState({ last_event_at: new Date().toISOString(), last_delivery_status: 'file_not_ingested' }));
  client.on('message.video', () => writeState({ last_event_at: new Date().toISOString(), last_delivery_status: 'video_not_ingested' }));
  client.on('error', () => writeState({ status: 'connection_error', authenticated: false, error_code: 'wecom_aibot_connection_error' }));

  const shutdown = createWecomAibotShutdown({
    writeState,
    disconnect: client.disconnect.bind(client),
  });
  process.on('SIGINT', () => { shutdown(); process.exit(0); });
  process.on('SIGTERM', () => { shutdown(); process.exit(0); });
  setInterval(() => writeState(), 30_000).unref();
  writeState({ status: 'connecting' });
  client.connect();
}
