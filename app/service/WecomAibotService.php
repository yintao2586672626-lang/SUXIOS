<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use think\facade\Db;
use Throwable;

/** Official WeCom AI Bot WebSocket relay: bind, archive, answer and delivery readback. */
final class WecomAibotService
{
    public const SDK_VERSION = '1.0.7';
    public const TRANSPORT = 'wecom_aibot_websocket';
    private const CODE_TABLE = 'wecom_aibot_binding_codes';
    private const BINDING_TABLE = WecomInboundService::BINDING_TABLE;
    private const EVENT_TABLE = WecomInboundService::EVENT_TABLE;
    private const CONTRACT_VERSION = WecomInboundService::CONTRACT_VERSION;

    /** @return array<string,mixed> */
    /** @param list<int> $hotelIds @return array<string,mixed> */
    public function capability(int $tenantId = 0, array $hotelIds = []): array
    {
        $package = root_path() . 'node_modules' . DIRECTORY_SEPARATOR . '@wecom' . DIRECTORY_SEPARATOR
            . 'aibot-node-sdk' . DIRECTORY_SEPARATOR . 'package.json';
        $installedVersion = '';
        if (is_file($package)) {
            $decoded = json_decode((string)file_get_contents($package), true);
            $installedVersion = is_array($decoded) ? trim((string)($decoded['version'] ?? '')) : '';
        }
        $botId = trim((string)env('SUXIOS_WECOM_AIBOT_ID', ''));
        $secret = trim((string)env('SUXIOS_WECOM_AIBOT_SECRET', ''));
        $relayToken = trim((string)env('SUXIOS_WECOM_AIBOT_RELAY_TOKEN', ''));
        $configured = $botId !== '' && $secret !== '' && strlen($relayToken) >= 32;
        $state = $this->workerState();
        $heartbeat = strtotime((string)($state['updated_at'] ?? '')) ?: 0;
        $authenticated = ($state['authenticated'] ?? false) === true
            && ($state['status'] ?? '') === 'authenticated'
            && $heartbeat >= time() - 90;
        $schemaStatus = $this->schemaStatus();
        $tablesReady = $schemaStatus === DatabaseSchemaRequirement::STATUS_PRESENT;
        $bindingCount = 0;
        $replyEnabledCount = 0;
        if ($tablesReady) {
            $hotelIds = array_values(array_unique(array_filter(array_map('intval', $hotelIds))));
            $bindingQuery = Db::name(self::BINDING_TABLE)
                ->where('transport', self::TRANSPORT)
                ->where('status', 'verified');
            $replyQuery = Db::name(self::BINDING_TABLE)
                ->where('transport', self::TRANSPORT)
                ->where('status', 'verified')
                ->where('reply_enabled', 1);
            if ($tenantId > 0) {
                $bindingQuery->where('tenant_id', $tenantId);
                $replyQuery->where('tenant_id', $tenantId);
            }
            if ($hotelIds !== []) {
                $bindingQuery->whereIn('hotel_id', $hotelIds);
                $replyQuery->whereIn('hotel_id', $hotelIds);
            }
            $bindingCount = (int)$bindingQuery->count();
            $replyEnabledCount = (int)$replyQuery->count();
        }
        $ready = $installedVersion === self::SDK_VERSION && $configured && $authenticated && $tablesReady;
        return [
            'contract_version' => 'wecom_aibot_runtime.v1',
            'status' => $ready ? 'ready' : 'blocked_not_configured',
            'transport' => self::TRANSPORT,
            'sdk' => [
                'package' => '@wecom/aibot-node-sdk',
                'required_version' => self::SDK_VERSION,
                'installed_version' => $installedVersion,
                'ready' => $installedVersion === self::SDK_VERSION,
            ],
            'configuration' => [
                'bot_id_present' => $botId !== '',
                'secret_present' => $secret !== '',
                'relay_token_valid' => strlen($relayToken) >= 32,
                'tables_ready' => $tablesReady,
                'schema_status' => $schemaStatus,
            ],
            'worker' => [
                'status' => (string)($state['status'] ?? 'not_running'),
                'authenticated' => $authenticated,
                'updated_at' => (string)($state['updated_at'] ?? ''),
                'pid' => max(0, (int)($state['pid'] ?? 0)),
            ],
            'bindings' => [
                'verified' => $bindingCount,
                'reply_enabled' => $replyEnabledCount,
            ],
            'error_code' => $ready ? null : $this->capabilityError(
                $installedVersion,
                $configured,
                $authenticated,
                $schemaStatus
            ),
            'boundaries' => [
                'outbound_websocket_only' => true,
                'public_callback_required' => false,
                'credentials_returned' => false,
                'raw_frame_persisted' => false,
                'reply_default_enabled' => false,
                'automatic_execution' => false,
                'ota_write' => false,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function createBindingCode(int $tenantId, int $hotelId, int $createdBy, string $label): array
    {
        $this->assertTablesReady();
        if ($tenantId <= 0 || $hotelId <= 0 || $createdBy <= 0) {
            throw new InvalidArgumentException('企业微信智能机器人绑定码范围无效');
        }
        $capability = $this->capability($tenantId, [$hotelId]);
        if ((string)($capability['status'] ?? '') !== 'ready') {
            throw new RuntimeException(
                '企业微信智能机器人尚未完成凭证配置与 WebSocket 认证，不能生成不可消费的绑定码',
                503
            );
        }
        $plain = $this->randomCode();
        $hash = $this->codeHash($plain);
        $expiresAt = date('Y-m-d H:i:s', time() + 15 * 60);
        $id = (int)Db::name(self::CODE_TABLE)->insertGetId([
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'code_hash' => $hash,
            'code_mask' => substr($plain, 0, 2) . '******',
            'label' => mb_substr(trim($label), 0, 120),
            'status' => 'active',
            'created_by' => $createdBy,
            'expires_at' => $expiresAt,
            'used_at' => null,
            'bound_binding_id' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $row = Db::name(self::CODE_TABLE)->where('id', $id)->find();
        if (!is_array($row) || !hash_equals($hash, (string)$row['code_hash'])) {
            throw new RuntimeException('企业微信智能机器人绑定码保存后回读失败');
        }
        return [
            'id' => $id,
            'hotel_id' => $hotelId,
            'binding_code' => $plain,
            'instruction' => '请在目标企业微信会话发送：绑定门店 ' . $plain,
            'expires_at' => $expiresAt,
            'single_use' => true,
            'reply_enabled_after_binding' => false,
            'persistence_status' => 'readback_verified',
        ];
    }

    /** @param list<int> $hotelIds @return array<string,mixed> */
    public function setReplyEnabled(int $bindingId, int $tenantId, array $hotelIds, bool $enabled): array
    {
        $this->assertTablesReady();
        $hotelIds = $this->hotelIds($hotelIds);
        $query = Db::name(self::BINDING_TABLE)
            ->where('id', $bindingId)
            ->where('transport', self::TRANSPORT)
            ->where('status', 'verified')
            ->whereIn('hotel_id', $hotelIds);
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new RuntimeException('wecom aibot binding not found', 404);
        }
        Db::name(self::BINDING_TABLE)->where('id', $bindingId)->update([
            'reply_enabled' => $enabled ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $readback = Db::name(self::BINDING_TABLE)->where('id', $bindingId)->find();
        if (!is_array($readback) || ((int)$readback['reply_enabled'] === 1) !== $enabled) {
            throw new RuntimeException('企业微信智能机器人回复开关保存后回读失败');
        }
        return [
            'id' => $bindingId,
            'hotel_id' => (int)$readback['hotel_id'],
            'transport' => self::TRANSPORT,
            'status' => (string)$readback['status'],
            'reply_enabled' => $enabled,
            'persistence_status' => 'readback_verified',
            'automatic_execution' => false,
            'ota_write' => false,
        ];
    }

    /** @param list<int> $hotelIds @return array<string,mixed> */
    public function disableBinding(int $bindingId, int $tenantId, array $hotelIds): array
    {
        $this->assertTablesReady();
        $hotelIds = $this->hotelIds($hotelIds);
        return Db::transaction(function () use ($bindingId, $tenantId, $hotelIds): array {
            $query = Db::name(self::BINDING_TABLE)
                ->where('id', $bindingId)
                ->where('transport', self::TRANSPORT)
                ->whereIn('hotel_id', $hotelIds)
                ->lock(true);
            if ($tenantId > 0) {
                $query->where('tenant_id', $tenantId);
            }
            $row = $query->find();
            if (!is_array($row) || !in_array((string)($row['status'] ?? ''), ['verified', 'disabled'], true)) {
                throw new RuntimeException('wecom aibot binding not found', 404);
            }
            if ((string)$row['status'] !== 'disabled') {
                $tombstone = hash('sha256', 'disabled:' . $bindingId . ':' . bin2hex(random_bytes(32)));
                Db::name(self::BINDING_TABLE)->where('id', $bindingId)->update([
                    'conversation_id_hash' => $tombstone,
                    'status' => 'disabled',
                    'reply_enabled' => 0,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $readback = Db::name(self::BINDING_TABLE)->where('id', $bindingId)->find();
            if (!is_array($readback)
                || (string)$readback['status'] !== 'disabled'
                || (int)$readback['reply_enabled'] !== 0
            ) {
                throw new RuntimeException('企业微信智能机器人解绑后回读失败');
            }
            return [
                'id' => $bindingId,
                'hotel_id' => (int)$readback['hotel_id'],
                'transport' => self::TRANSPORT,
                'status' => 'disabled',
                'reply_enabled' => false,
                'conversation_reference_released' => true,
                'historical_events_retained' => true,
                'persistence_status' => 'readback_verified',
                'automatic_execution' => false,
                'ota_write' => false,
            ];
        });
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function ingest(array $input): array
    {
        $this->assertTablesReady();
        $configuredBotId = trim((string)env('SUXIOS_WECOM_AIBOT_ID', ''));
        $botId = trim((string)($input['aibot_id'] ?? ''));
        if ($configuredBotId === '' || $botId === '' || !hash_equals($configuredBotId, $botId)) {
            throw new RuntimeException('企业微信智能机器人身份不匹配', 403);
        }
        $eventId = mb_substr(trim((string)($input['msg_id'] ?? '')), 0, 191);
        $conversationId = trim((string)($input['conversation_id'] ?? ''));
        $senderId = trim((string)($input['sender_id'] ?? ''));
        $messageType = strtolower(trim((string)($input['message_type'] ?? '')));
        $rawContent = $this->safeText((string)($input['content'] ?? ''), 1000);
        if ($eventId === '' || $conversationId === '' || $senderId === '') {
            throw new InvalidArgumentException('企业微信智能机器人事件身份字段不完整');
        }
        if (!in_array($messageType, ['text', 'voice'], true)) {
            return [
                'status' => 'blocked',
                'block_code' => 'message_type_unsupported',
                'reply_allowed' => false,
                'delivery_status' => 'not_sent',
            ];
        }
        if ($rawContent === '') {
            return [
                'status' => 'blocked',
                'block_code' => 'message_content_empty',
                'reply_allowed' => false,
                'delivery_status' => 'not_sent',
            ];
        }
        $bindingCode = $this->bindingCodeFromContent($rawContent);
        $isBindingCommand = $bindingCode !== '';
        $storedContent = $isBindingCommand ? '绑定门店 ********' : $rawContent;
        $contentIdentityDigest = hash('sha256', 'wecom-aibot-event-content-v1|' . $rawContent);
        $conversationHash = $this->conversationHash($conversationId);
        $binding = Db::name(self::BINDING_TABLE)
            ->where('conversation_id_hash', $conversationHash)
            ->where('transport', self::TRANSPORT)
            ->where('status', 'verified')
            ->find();
        $bindingConfirmation = false;
        if (!is_array($binding)) {
            if (!$isBindingCommand) {
                return [
                    'status' => 'blocked_not_bound',
                    'block_code' => 'wecom_conversation_not_bound',
                    'reply_allowed' => false,
                    'delivery_status' => 'not_sent',
                ];
            }
            $binding = $this->consumeBindingCode($bindingCode, $conversationHash);
            $bindingConfirmation = true;
        } elseif ($isBindingCommand) {
            $bindingConfirmation = $this->bindingCodeBelongsToBinding($bindingCode, (int)$binding['id']);
        }

        $answer = $bindingConfirmation
            ? [
                'status' => 'reply_ready',
                'intent' => 'hotel_binding_confirmation',
                'metric_scope' => 'ota_channel',
                'reply_text' => '绑定成功。当前会话已关联到宿析OS门店；默认只归档不回复，请在宿析OS中主动开启“允许企微回复”后再提问。',
                'sources' => [],
                'data_gaps' => [],
            ]
            : ($isBindingCommand
                ? [
                    'status' => 'blocked',
                    'intent' => 'hotel_binding_confirmation',
                    'metric_scope' => 'ota_channel',
                    'reply_text' => '',
                    'sources' => [],
                    'data_gaps' => [],
                    'code' => 'binding_code_not_applicable',
                ]
                : $this->answer((int)$binding['hotel_id'], $rawContent));
        $event = $this->archive(
            $binding,
            $eventId,
            $messageType,
            $senderId,
            $storedContent,
            $contentIdentityDigest,
            $this->normalizeOccurredAt($input['create_time'] ?? null),
            $answer,
            $bindingConfirmation || !$isBindingCommand
        );
        $event['sender_binding_projection'] = $this->projectSenderBinding($event);
        $event['task_receipt_projection'] = (new WecomTaskReceiptService())->projectArchivedEvent($event);
        $eventAnswer = is_array($event['answer'] ?? null) ? $event['answer'] : [];
        $event['reply_allowed'] = ($event['duplicate'] ?? false) !== true
            && (string)($event['delivery_status'] ?? 'not_sent') === 'not_sent'
            && ($bindingConfirmation || (int)($binding['reply_enabled'] ?? 0) === 1);
        $event['binding_confirmation'] = $bindingConfirmation;
        $event['reply_text'] = $event['reply_allowed'] ? (string)($eventAnswer['reply_text'] ?? '') : '';
        return $event;
    }

    /** @param array<string,mixed> $event @return array<string,mixed> */
    private function projectSenderBinding(array $event): array
    {
        try {
            return (new WecomTaskReceiptService())->consumeSenderBindingChallenge($event);
        } catch (Throwable $error) {
            $code = trim($error->getMessage());
            if (!str_starts_with($code, 'wecom_sender_binding_')) {
                $code = 'wecom_sender_binding_projection_failed';
            }
            return ['status' => 'blocked', 'code' => $code];
        }
    }

    /** @return array<string,mixed> */
    public function recordDelivery(int $eventId, string $status, string $reference): array
    {
        $this->assertTablesReady();
        if (!in_array($status, ['sent', 'failed', 'outcome_unknown'], true)) {
            throw new InvalidArgumentException('企业微信智能机器人回执状态无效');
        }
        $reference = in_array($reference, [
            'wecom_aibot:errcode=0',
            'wecom_aibot:reply_failed',
            'wecom_aibot:outcome_unknown',
        ], true) ? $reference : 'wecom_aibot:outcome_unknown';
        $requiredReference = [
            'sent' => 'wecom_aibot:errcode=0',
            'failed' => 'wecom_aibot:reply_failed',
            'outcome_unknown' => 'wecom_aibot:outcome_unknown',
        ][$status];
        if ($reference !== $requiredReference) {
            throw new InvalidArgumentException('企业微信智能机器人回执状态与凭证不匹配');
        }
        return Db::transaction(function () use ($eventId, $status, $reference): array {
            $row = Db::name(self::EVENT_TABLE)
                ->where('id', $eventId)
                ->where('transport', self::TRANSPORT)
                ->lock(true)
                ->find();
            if (!is_array($row)) {
                throw new RuntimeException('wecom aibot event not found', 404);
            }
            $this->assertEventDigest($this->normalizeEvent($row));
            if ((string)$row['delivery_status'] === 'sent' && $status !== 'sent') {
                throw new RuntimeException('已确认送达的企业微信回复不能降级覆盖', 409);
            }
            if ((string)$row['delivery_status'] !== 'sent') {
                $event = $this->normalizeEvent($row);
                $event['delivery_status'] = $status;
                $event['delivery_reference'] = $reference;
                Db::name(self::EVENT_TABLE)->where('id', $eventId)->update([
                    'delivery_status' => $status,
                    'delivery_reference' => $reference,
                    'content_digest' => $this->eventDigest($event),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $readback = Db::name(self::EVENT_TABLE)->where('id', $eventId)->find();
            if (!is_array($readback)) {
                throw new RuntimeException('企业微信智能机器人回执保存后回读失败');
            }
            $result = $this->normalizeEvent($readback);
            $this->assertEventDigest($result);
            $result['persistence_status'] = 'readback_verified';
            return $result;
        });
    }

    /** @return array<string,mixed> */
    private function answer(int $hotelId, string $content): array
    {
        try {
            $answer = (new WechatMonitorQueryService())->answer($hotelId, $content);
            return [
                'status' => 'reply_ready',
                'intent' => (string)($answer['intent'] ?? 'unknown'),
                'metric_scope' => (string)($answer['metric_scope'] ?? 'ota_channel'),
                'reply_text' => (string)($answer['reply_text'] ?? ''),
                'sources' => (array)($answer['sources'] ?? []),
                'data_gaps' => (array)($answer['data_gaps'] ?? []),
            ];
        } catch (Throwable) {
            return [
                'status' => 'blocked',
                'intent' => 'unknown',
                'metric_scope' => 'ota_channel',
                'reply_text' => '',
                'sources' => [],
                'data_gaps' => [],
                'code' => 'query_answer_failed',
            ];
        }
    }

    /** @param array<string,mixed> $binding @param array<string,mixed> $answer @return array<string,mixed> */
    private function archive(
        array $binding,
        string $eventId,
        string $messageType,
        string $senderId,
        string $storedContent,
        string $contentIdentityDigest,
        ?string $occurredAt,
        array $answer,
        bool $allowLegacyStoredContentDigest
    ): array {
        $senderHash = hash('sha256', 'wecom-sender-v1|' . $senderId);
        $payloadDigest = $this->digest([
            'external_event_id' => $eventId,
            'message_type' => $messageType,
            'transport' => self::TRANSPORT,
            'sender_id_hash' => $senderHash,
            'content_identity_digest' => $contentIdentityDigest,
            'occurred_at' => $occurredAt,
        ]);
        $existing = Db::name(self::EVENT_TABLE)
            ->where('binding_id', (int)$binding['id'])
            ->where('external_event_id', $eventId)
            ->find();
        if (is_array($existing)) {
            if (!$this->eventPayloadMatches(
                $existing,
                $payloadDigest,
                $allowLegacyStoredContentDigest ? $storedContent : null
            )) {
                throw new RuntimeException('企业微信智能机器人事件幂等键内容冲突', 409);
            }
            $readback = $this->normalizeEvent($existing);
            $this->assertEventDigest($readback);
            $readback['duplicate'] = true;
            $readback['persistence_status'] = 'duplicate_readback_verified';
            return $readback;
        }
        $processingStatus = (string)($answer['status'] ?? '') === 'reply_ready' ? 'reply_ready' : 'blocked';
        $blockCode = $processingStatus === 'reply_ready' ? null : (string)($answer['code'] ?? 'query_blocked');
        $evidenceRefs = array_values(array_unique(array_filter(array_map(
            static fn(mixed $item): string => mb_substr(trim((string)$item), 0, 220),
            (array)($answer['sources'] ?? [])
        ))));
        $record = [
            'contract_version' => self::CONTRACT_VERSION,
            'binding_id' => (int)$binding['id'],
            'tenant_id' => (int)$binding['tenant_id'],
            'hotel_id' => (int)$binding['hotel_id'],
            'external_event_id' => $eventId,
            'payload_digest' => $payloadDigest,
            'occurred_at' => $occurredAt,
            'message_type' => $messageType,
            'transport' => self::TRANSPORT,
            'sender_id_hash' => $senderHash,
            'content_text' => $storedContent,
            'archive_status' => 'readback_verified',
            'processing_status' => $processingStatus,
            'block_code' => $blockCode,
            'answer' => $answer,
            'evidence_refs' => $evidenceRefs,
            'delivery_status' => 'not_sent',
            'delivery_reference' => null,
        ];
        $now = date('Y-m-d H:i:s');
        try {
            $id = (int)Db::name(self::EVENT_TABLE)->insertGetId([
                'binding_id' => $record['binding_id'],
                'tenant_id' => $record['tenant_id'],
                'hotel_id' => $record['hotel_id'],
                'external_event_id' => $eventId,
                'payload_digest' => $payloadDigest,
                'occurred_at' => $occurredAt,
                'message_type' => $messageType,
                'transport' => self::TRANSPORT,
                'sender_id_hash' => $senderHash,
                'content_text' => $storedContent,
                'archive_status' => 'readback_verified',
                'processing_status' => $processingStatus,
                'block_code' => $blockCode,
                'answer_json' => $this->encode($answer),
                'evidence_refs_json' => $this->encode($evidenceRefs),
                'delivery_status' => 'not_sent',
                'delivery_reference' => null,
                'content_digest' => $this->digest($record),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (Throwable $e) {
            $concurrent = Db::name(self::EVENT_TABLE)
                ->where('binding_id', (int)$binding['id'])
                ->where('external_event_id', $eventId)
                ->find();
            if (!is_array($concurrent) || !$this->eventPayloadMatches(
                $concurrent,
                $payloadDigest,
                $allowLegacyStoredContentDigest ? $storedContent : null
            )) {
                throw $e;
            }
            $readback = $this->normalizeEvent($concurrent);
            $this->assertEventDigest($readback);
            $readback['duplicate'] = true;
            $readback['persistence_status'] = 'duplicate_readback_verified';
            return $readback;
        }
        $row = Db::name(self::EVENT_TABLE)->where('id', $id)->find();
        if (!is_array($row)) {
            throw new RuntimeException('企业微信智能机器人事件保存后回读失败');
        }
        $readback = $this->normalizeEvent($row);
        $this->assertEventDigest($readback);
        $readback['duplicate'] = false;
        $readback['persistence_status'] = 'readback_verified';
        return $readback;
    }

    /** @return array<string,mixed> */
    private function consumeBindingCode(string $plainCode, string $conversationHash): array
    {
        return Db::transaction(function () use ($plainCode, $conversationHash): array {
            $code = Db::name(self::CODE_TABLE)
                ->where('code_hash', $this->codeHash($plainCode))
                ->where('status', 'active')
                ->where('expires_at', '>', date('Y-m-d H:i:s'))
                ->lock(true)
                ->find();
            if (!is_array($code)) {
                throw new RuntimeException('企业微信智能机器人绑定码无效或已过期', 422);
            }
            $existing = Db::name(self::BINDING_TABLE)->where('conversation_id_hash', $conversationHash)->find();
            if (is_array($existing)) {
                throw new RuntimeException('当前企业微信会话已绑定酒店', 409);
            }
            $now = date('Y-m-d H:i:s');
            $bindingId = (int)Db::name(self::BINDING_TABLE)->insertGetId([
                'tenant_id' => (int)$code['tenant_id'],
                'hotel_id' => (int)$code['hotel_id'],
                'binding_key' => 'aibot_' . substr($conversationHash, 0, 32),
                'conversation_id_hash' => $conversationHash,
                'label' => (string)$code['label'],
                'transport' => self::TRANSPORT,
                'status' => 'verified',
                'reply_enabled' => 0,
                'created_by' => (int)$code['created_by'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            Db::name(self::CODE_TABLE)->where('id', (int)$code['id'])->update([
                'status' => 'used',
                'used_at' => $now,
                'bound_binding_id' => $bindingId,
            ]);
            $binding = Db::name(self::BINDING_TABLE)->where('id', $bindingId)->find();
            if (!is_array($binding) || (string)$binding['status'] !== 'verified') {
                throw new RuntimeException('企业微信会话绑定保存后回读失败');
            }
            return $binding;
        });
    }

    private function bindingCodeBelongsToBinding(string $plainCode, int $bindingId): bool
    {
        if ($bindingId <= 0) {
            return false;
        }
        $row = Db::name(self::CODE_TABLE)
            ->where('code_hash', $this->codeHash($plainCode))
            ->where('status', 'used')
            ->where('bound_binding_id', $bindingId)
            ->find();
        return is_array($row);
    }

    private function eventPayloadMatches(array $row, string $payloadDigest, ?string $legacyContent): bool
    {
        $storedDigest = (string)($row['payload_digest'] ?? '');
        if (hash_equals($storedDigest, $payloadDigest)) {
            return true;
        }
        if ($legacyContent === null) {
            return false;
        }

        $legacyDigest = $this->digest([
            'external_event_id' => (string)($row['external_event_id'] ?? ''),
            'message_type' => (string)($row['message_type'] ?? ''),
            'transport' => (string)($row['transport'] ?? ''),
            'sender_id_hash' => (string)($row['sender_id_hash'] ?? ''),
            'content_text' => $legacyContent,
            'occurred_at' => isset($row['occurred_at']) ? (string)$row['occurred_at'] : null,
        ]);
        return hash_equals($storedDigest, $legacyDigest);
    }

    /** @return array<string,mixed> */
    private function normalizeEvent(array $row): array
    {
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'id' => (int)($row['id'] ?? 0),
            'binding_id' => (int)($row['binding_id'] ?? 0),
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'hotel_id' => (int)($row['hotel_id'] ?? 0),
            'external_event_id' => (string)($row['external_event_id'] ?? ''),
            'payload_digest' => (string)($row['payload_digest'] ?? ''),
            'occurred_at' => isset($row['occurred_at']) ? (string)$row['occurred_at'] : null,
            'message_type' => (string)($row['message_type'] ?? ''),
            'transport' => (string)($row['transport'] ?? ''),
            'sender_id_hash' => (string)($row['sender_id_hash'] ?? ''),
            'content_text' => isset($row['content_text']) ? (string)$row['content_text'] : null,
            'archive_status' => (string)($row['archive_status'] ?? ''),
            'processing_status' => (string)($row['processing_status'] ?? ''),
            'block_code' => isset($row['block_code']) && trim((string)$row['block_code']) !== '' ? (string)$row['block_code'] : null,
            'answer' => $this->decode($row['answer_json'] ?? null),
            'evidence_refs' => $this->decode($row['evidence_refs_json'] ?? null),
            'delivery_status' => (string)($row['delivery_status'] ?? 'not_sent'),
            'delivery_reference' => isset($row['delivery_reference']) && trim((string)$row['delivery_reference']) !== '' ? (string)$row['delivery_reference'] : null,
            'content_digest' => (string)($row['content_digest'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }

    private function eventDigest(array $event): string
    {
        return $this->digest(array_intersect_key($event, array_flip([
            'contract_version', 'binding_id', 'tenant_id', 'hotel_id', 'external_event_id',
            'payload_digest', 'occurred_at', 'message_type', 'transport', 'sender_id_hash',
            'content_text', 'archive_status', 'processing_status', 'block_code', 'answer',
            'evidence_refs', 'delivery_status', 'delivery_reference',
        ])));
    }

    private function assertEventDigest(array $event): void
    {
        if (!hash_equals((string)$event['content_digest'], $this->eventDigest($event))) {
            throw new RuntimeException('企业微信智能机器人事件回读摘要不一致');
        }
    }

    private function workerState(): array
    {
        $path = runtime_path() . 'wecom-aibot-state.json';
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string)file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function capabilityError(string $installedVersion, bool $configured, bool $authenticated, string $schemaStatus): string
    {
        if ($installedVersion !== self::SDK_VERSION) {
            return 'wecom_aibot_sdk_missing_or_mismatched';
        }
        if ($schemaStatus === DatabaseSchemaRequirement::STATUS_UNREADABLE) {
            return 'wecom_aibot_schema_unreadable';
        }
        if ($schemaStatus !== DatabaseSchemaRequirement::STATUS_PRESENT) {
            return 'wecom_aibot_tables_missing';
        }
        if (!$configured) {
            return 'wecom_aibot_credentials_missing';
        }
        if (!$authenticated) {
            return 'wecom_aibot_worker_not_authenticated';
        }
        return 'wecom_aibot_unavailable';
    }

    private function bindingCodeFromContent(string $content): string
    {
        return preg_match('/^绑定门店\s+([A-Z0-9]{8})$/u', strtoupper(trim($content)), $matches) === 1
            ? (string)$matches[1]
            : '';
    }

    private function randomCode(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $code;
    }

    private function codeHash(string $plain): string
    {
        return hash('sha256', 'wecom-aibot-binding-code-v1|' . strtoupper(trim($plain)));
    }

    private function conversationHash(string $value): string
    {
        return hash('sha256', 'wecom-conversation-v1|' . trim($value));
    }

    private function normalizeOccurredAt(mixed $value): ?string
    {
        if (!is_numeric($value) || (int)$value <= 0) {
            return null;
        }
        $timestamp = (int)$value;
        if ($timestamp > 10_000_000_000) {
            $timestamp = (int)floor($timestamp / 1000);
        }
        return gmdate('Y-m-d H:i:s', $timestamp + 8 * 3600);
    }

    /** @param list<int> $hotelIds @return list<int> */
    private function hotelIds(array $hotelIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $hotelIds), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            throw new InvalidArgumentException('企业微信智能机器人查询缺少酒店范围');
        }
        return $ids;
    }

    private function tablesReady(): bool
    {
        return $this->schemaStatus() === DatabaseSchemaRequirement::STATUS_PRESENT;
    }

    private function schemaStatus(): string
    {
        $statuses = [];
        foreach ([self::CODE_TABLE, self::BINDING_TABLE, self::EVENT_TABLE] as $table) {
            $statuses[] = DatabaseSchemaRequirement::inspectTable($table)['status'];
        }
        if (in_array(DatabaseSchemaRequirement::STATUS_UNREADABLE, $statuses, true)) {
            return DatabaseSchemaRequirement::STATUS_UNREADABLE;
        }
        return in_array(DatabaseSchemaRequirement::STATUS_MISSING, $statuses, true)
            ? DatabaseSchemaRequirement::STATUS_MISSING
            : DatabaseSchemaRequirement::STATUS_PRESENT;
    }

    private function assertTablesReady(): void
    {
        $status = $this->schemaStatus();
        if ($status === DatabaseSchemaRequirement::STATUS_MISSING) {
            throw new RuntimeException('企业微信智能机器人表尚未迁移', 503);
        }
        if ($status !== DatabaseSchemaRequirement::STATUS_PRESENT) {
            throw new RuntimeException('企业微信智能机器人表结构检查失败', 503);
        }
    }

    private function safeText(string $value, int $limit): string
    {
        return mb_substr(trim(str_replace("\0", '', $value)), 0, $limit);
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    private function digest(array $value): string
    {
        return hash('sha256', json_encode($this->canonical($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonical($item);
        }
        return $value;
    }
}
