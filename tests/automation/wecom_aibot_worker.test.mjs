import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import {
  createWecomAibotMessageHandler,
  createWecomAibotShutdown,
  normalizeWecomAibotRelayBase,
  wecomAibotCredentialError,
} from '../../scripts/lib/wecom_aibot_delivery.mjs';

const workerSource = readFileSync(new URL('../../scripts/wecom_aibot_worker.mjs', import.meta.url), 'utf8');

const frame = (content = '请给我昨日经营摘要') => ({
  body: {
    aibotid: 'bot-1',
    msgid: 'msg-1',
    chatid: 'chat-1',
    chattype: 'group',
    create_time: 123,
    from: { userid: 'operator-1' },
    text: { content },
  },
});

const harness = (relayResult) => {
  const calls = { relay: [], reply: [], delivery: [], state: [], wait: [] };
  const handle = createWecomAibotMessageHandler({
    botId: 'bot-default',
    relay: async (...args) => {
      calls.relay.push(args);
      if (relayResult instanceof Error) throw relayResult;
      return relayResult;
    },
    replyStream: async (...args) => {
      calls.reply.push(args);
      return { errcode: 0 };
    },
    requestId: () => 'req-test',
    recordDelivery: async (...args) => { calls.delivery.push(args); },
    writeState: (patch) => { calls.state.push(patch); },
    now: () => new Date('2026-08-25T08:00:00.000Z'),
    wait: async (milliseconds) => { calls.wait.push(milliseconds); },
  });
  return { calls, handle };
};

test('credential gate distinguishes missing credentials from a missing relay token', () => {
  assert.equal(wecomAibotCredentialError('', 'secret', 'x'.repeat(32)), 'wecom_aibot_credentials_missing');
  assert.equal(wecomAibotCredentialError('bot', '', 'x'.repeat(32)), 'wecom_aibot_credentials_missing');
  assert.equal(wecomAibotCredentialError('bot', 'secret', 'short'), 'wecom_aibot_relay_token_missing');
  assert.equal(wecomAibotCredentialError('bot', 'secret', 'x'.repeat(32)), null);
});

test('relay base accepts only a bare loopback HTTP origin before authenticated fetch', () => {
  assert.equal(normalizeWecomAibotRelayBase(undefined), 'http://127.0.0.1:8080');
  assert.equal(normalizeWecomAibotRelayBase('http://localhost:18080/'), 'http://localhost:18080');
  assert.equal(normalizeWecomAibotRelayBase('http://[::1]:8080'), 'http://[::1]:8080');
  for (const value of [
    'https://127.0.0.1:8080',
    'http://example.com:8080',
    'http://localhost.example.com:8080',
    'http://user:pass@127.0.0.1:8080',
    'http://127.0.0.1:8080/api',
    'http://127.0.0.1:8080/?next=external',
  ]) {
    assert.throws(
      () => normalizeWecomAibotRelayBase(value),
      /wecom_aibot_relay_base_/,
      value,
    );
  }
  assert.ok(workerSource.indexOf('normalizeWecomAibotRelayBase') < workerSource.indexOf('X-SUXIOS-Relay-Token'));
});

test('duplicate, already-delivered, disallowed, and empty replies never call the external SDK', async () => {
  for (const result of [
    { duplicate: true, delivery_status: 'not_sent', reply_allowed: true, reply_text: 'reply' },
    { duplicate: false, delivery_status: 'sent', reply_allowed: true, reply_text: 'reply' },
    { duplicate: false, delivery_status: 'not_sent', reply_allowed: false, reply_text: 'reply' },
    { duplicate: false, delivery_status: 'not_sent', reply_allowed: true, reply_text: '   ' },
  ]) {
    const { calls, handle } = harness(result);
    await handle(frame(), 'text');
    assert.equal(calls.reply.length, 0);
    assert.equal(calls.delivery.length, 0);
  }
});

test('successful reply is sent once, bounded to 12000 characters, and stored as sent', async () => {
  const { calls, handle } = harness({
    id: 41,
    duplicate: false,
    delivery_status: 'not_sent',
    reply_allowed: true,
    reply_text: '答'.repeat(12_100),
  });

  await handle(frame(), 'text');

  assert.equal(calls.reply.length, 1);
  assert.equal(calls.reply[0][1], 'req-test');
  assert.equal(calls.reply[0][2].length, 12_000);
  assert.equal(calls.reply[0][3], true);
  assert.deepEqual(calls.delivery, [[41, 'sent', 'wecom_aibot:errcode=0']]);
});

test('missing or nonzero SDK errcode is recorded as outcome_unknown', async () => {
  for (const receipt of [{}, { errcode: 40001 }, null]) {
    const deliveries = [];
    const handle = createWecomAibotMessageHandler({
      botId: 'bot',
      relay: async () => ({ id: 42, delivery_status: 'not_sent', reply_allowed: true, reply_text: 'reply' }),
      replyStream: async () => receipt,
      requestId: () => 'req',
      recordDelivery: async (...args) => { deliveries.push(args); },
      writeState() {},
    });
    await handle(frame(), 'text');
    assert.deepEqual(deliveries, [[42, 'outcome_unknown', 'wecom_aibot:outcome_unknown']]);
  }
});

test('relay failure retries the same event and still fails closed before external reply', async () => {
  const failed = harness(new Error('relay unavailable'));
  await assert.rejects(failed.handle(frame(), 'text'), /relay unavailable/);
  assert.equal(failed.calls.relay.length, 3);
  assert.deepEqual(failed.calls.wait, [250, 500]);
  assert.equal(failed.calls.reply.length, 0);
  assert.deepEqual(failed.calls.state.at(-1), {
    last_delivery_status: 'relay_failed',
    relay_retry_attempts: 3,
  });

  const incomplete = harness({ id: 1, delivery_status: 'not_sent', reply_allowed: true, reply_text: 'reply' });
  await incomplete.handle({ body: { text: { content: 'reply' } } }, 'text');
  assert.equal(incomplete.calls.relay.length, 0);
  assert.equal(incomplete.calls.reply.length, 0);
});

test('transient receipt projection failure recovers through the same-event relay retry', async () => {
  const calls = { relay: 0, wait: [], state: [], reply: 0 };
  const handle = createWecomAibotMessageHandler({
    botId: 'bot',
    relay: async () => {
      calls.relay++;
      if (calls.relay === 1) throw new Error('receipt projection temporarily unavailable');
      return {
        id: 45,
        duplicate: true,
        delivery_status: 'not_sent',
        reply_allowed: false,
        reply_text: '',
      };
    },
    replyStream: async () => { calls.reply++; },
    requestId: () => 'req',
    recordDelivery: async () => {},
    writeState: (patch) => { calls.state.push(patch); },
    wait: async (milliseconds) => { calls.wait.push(milliseconds); },
  });

  await handle(frame('{"task_id":45,"status":"completed"}'), 'text');

  assert.equal(calls.relay, 2);
  assert.deepEqual(calls.wait, [250]);
  assert.equal(calls.reply, 0);
  assert.deepEqual(calls.state.at(-1), {
    last_delivery_status: 'relay_recovered',
    relay_retry_attempts: 1,
  });
});

test('shutdown records stopped state before disconnecting', () => {
  const order = [];
  const shutdown = createWecomAibotShutdown({
    writeState: (patch) => { order.push(['state', patch]); },
    disconnect: () => { order.push(['disconnect']); },
  });
  shutdown();
  assert.deepEqual(order, [
    ['state', { status: 'stopped', authenticated: false }],
    ['disconnect'],
  ]);
});
