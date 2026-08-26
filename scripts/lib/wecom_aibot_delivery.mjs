const text = (value) => String(value ?? '').trim();

export function wecomAibotCredentialError(botId, secret, relayToken) {
  if (text(botId) === '' || text(secret) === '') return 'wecom_aibot_credentials_missing';
  if (text(relayToken).length < 32) return 'wecom_aibot_relay_token_missing';
  return null;
}

export function createWecomAibotShutdown({ writeState, disconnect }) {
  if (typeof writeState !== 'function' || typeof disconnect !== 'function') {
    throw new TypeError('wecom_aibot_shutdown_dependencies_invalid');
  }
  return () => {
    writeState({ status: 'stopped', authenticated: false });
    disconnect();
  };
}

export function createWecomAibotMessageHandler({
  botId,
  relay,
  replyStream,
  requestId,
  recordDelivery,
  writeState,
  now = () => new Date(),
}) {
  for (const [name, dependency] of Object.entries({
    relay,
    replyStream,
    requestId,
    recordDelivery,
    writeState,
    now,
  })) {
    if (typeof dependency !== 'function') {
      throw new TypeError(`wecom_aibot_${name}_invalid`);
    }
  }

  return async (frame, messageType) => {
    const body = frame?.body || {};
    const content = messageType === 'voice'
      ? text(body.voice?.content)
      : text(body.text?.content);
    const conversationId = text(body.chatid || body.from?.userid);
    const senderId = text(body.from?.userid);
    const msgId = text(body.msgid);
    if (!content || !conversationId || !senderId || !msgId) return;

    writeState({ last_event_at: now().toISOString() });
    let result;
    try {
      result = await relay('/api/internal/wecom-aibot/events', {
        aibot_id: text(body.aibotid || botId),
        msg_id: msgId,
        conversation_id: conversationId,
        sender_id: senderId,
        chat_type: text(body.chattype),
        create_time: body.create_time ?? null,
        message_type: messageType,
        content,
      });
    } catch {
      writeState({ last_delivery_status: 'relay_failed' });
      return;
    }

    if (result?.duplicate === true
      || result?.delivery_status !== 'not_sent'
      || result?.reply_allowed !== true
      || !text(result.reply_text)
    ) return;

    const eventId = Number(result.id || 0);
    try {
      const receipt = await replyStream(
        frame,
        requestId(),
        String(result.reply_text).slice(0, 12_000),
        true,
      );
      if (!receipt || typeof receipt !== 'object'
        || !Object.prototype.hasOwnProperty.call(receipt, 'errcode')
        || Number(receipt.errcode) !== 0
      ) throw new Error('wecom_reply_rejected');
      await recordDelivery(eventId, 'sent', 'wecom_aibot:errcode=0');
    } catch {
      await recordDelivery(eventId, 'outcome_unknown', 'wecom_aibot:outcome_unknown');
    }
  };
}
