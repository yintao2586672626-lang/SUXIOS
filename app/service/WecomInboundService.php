<?php
declare(strict_types=1);

namespace app\service;

use InvalidArgumentException;
use RuntimeException;
use SimpleXMLElement;
use think\facade\Db;
use Throwable;

/** Encrypted WeCom callback boundary plus deduplicated, read-only operating reply archive. */
final class WecomInboundService
{
    public const BINDING_TABLE = 'wecom_inbound_bindings';
    public const EVENT_TABLE = 'wecom_inbound_events';
    public const CONTRACT_VERSION = 'wecom_inbound_archive.v1';
    private const PROCESSING_LEASE_SECONDS = 60;
    private const TERMINAL_PROCESSING_STATUSES = ['reply_ready', 'blocked', 'failed'];
    private const EVENT_PROCESSING_COLUMNS = [
        'processing_status',
        'processing_claim_token',
        'processing_lease_expires_at',
    ];

    /** @return array<string,mixed> */
    /** @param list<int> $hotelIds @return array<string,mixed> */
    public function capability(int $tenantId = 0, array $hotelIds = []): array
    {
        $config = $this->callbackConfig();
        $schemaStatus = $this->schemaStatus();
        $tablesReady = $schemaStatus === DatabaseSchemaRequirement::STATUS_PRESENT;
        $cryptoReady = function_exists('openssl_decrypt') && function_exists('simplexml_load_string');
        $publicHost = strtolower((string)(parse_url($config['public_base_url'], PHP_URL_HOST) ?? ''));
        $publicHttpsReady = str_starts_with($config['public_base_url'], 'https://')
            && $publicHost !== ''
            && !in_array($publicHost, ['127.0.0.1', 'localhost', '::1'], true);
        $verifiedBindings = 0;
        if ($tablesReady) {
            $query = Db::name(self::BINDING_TABLE)
                ->where('transport', 'wecom_app_callback')
                ->where('status', 'verified');
            if ($tenantId > 0) {
                $query->where('tenant_id', $tenantId);
            }
            $hotelIds = array_values(array_unique(array_filter(array_map('intval', $hotelIds))));
            if ($hotelIds !== []) {
                $query->whereIn('hotel_id', $hotelIds);
            }
            $verifiedBindings = (int)$query->count();
        }
        $ready = $tablesReady && $cryptoReady && $this->configReady($config)
            && $publicHttpsReady && $verifiedBindings > 0;
        return [
            'contract_version' => self::CONTRACT_VERSION,
            'status' => $ready ? 'ready_for_callback' : 'blocked_not_configured',
            'callback_path_template' => '/api/integrations/wecom/callback/{binding_key}',
            'configuration' => [
                'token_present' => $config['token'] !== '',
                'aes_key_valid' => strlen($config['aes_key']) === 43,
                'corp_id_present' => $config['corp_id'] !== '',
                'tables_ready' => $tablesReady,
                'schema_status' => $schemaStatus,
                'crypto_runtime_ready' => $cryptoReady,
                'public_https_ready' => $publicHttpsReady,
                'verified_binding_count' => $verifiedBindings,
            ],
            'error_code' => $ready ? null : $this->configError(
                $config,
                $schemaStatus,
                $cryptoReady,
                $publicHttpsReady,
                $verifiedBindings
            ),
            'boundaries' => [
                'robot_webhook_receive' => false,
                'encrypted_custom_app_callback_required' => true,
                'raw_callback_persisted' => false,
                'credentials_returned' => false,
                'automatic_reply_sent' => false,
                'automatic_execution' => false,
                'ota_write' => false,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function saveBinding(
        int $tenantId,
        int $hotelId,
        int $createdBy,
        string $conversationId,
        string $label,
        string $bindingKey = ''
    ): array {
        $this->assertTablesReady();
        if ($tenantId <= 0 || $hotelId <= 0 || $createdBy <= 0) {
            throw new InvalidArgumentException('企业微信入站绑定范围无效');
        }
        $conversationId = trim($conversationId);
        if ($conversationId === '' || mb_strlen($conversationId) > 191) {
            throw new InvalidArgumentException('conversation_id 必填且不能超过191字');
        }
        $bindingKey = trim($bindingKey);
        if ($bindingKey === '') {
            $bindingKey = bin2hex(random_bytes(16));
        }
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $bindingKey) !== 1) {
            throw new InvalidArgumentException('binding_key 仅支持16-64位字母、数字、下划线或短横线');
        }
        $conversationHash = $this->conversationHash($conversationId);
        $payload = [
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'conversation_id_hash' => $conversationHash,
            'label' => mb_substr(trim($label), 0, 120),
            'transport' => 'wecom_app_callback',
            'status' => 'pending_verification',
            'reply_enabled' => 0,
            'created_by' => $createdBy,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        $existing = Db::name(self::BINDING_TABLE)->where('binding_key', $bindingKey)->find();
        if (is_array($existing)) {
            if ((int)$existing['tenant_id'] !== $tenantId
                || (int)$existing['hotel_id'] !== $hotelId
                || (string)($existing['transport'] ?? '') !== 'wecom_app_callback'
            ) {
                throw new RuntimeException('binding_key 已被其他酒店占用', 409);
            }
            Db::name(self::BINDING_TABLE)->where('id', (int)$existing['id'])->update($payload);
            $id = (int)$existing['id'];
            $created = false;
        } else {
            $payload['binding_key'] = $bindingKey;
            $payload['created_at'] = date('Y-m-d H:i:s');
            try {
                $id = (int)Db::name(self::BINDING_TABLE)->insertGetId($payload);
            } catch (Throwable $e) {
                $conflict = Db::name(self::BINDING_TABLE)->where('conversation_id_hash', $conversationHash)->find();
                if (is_array($conflict)) {
                    throw new RuntimeException('企业微信会话已绑定其他回调标识', 409, $e);
                }
                throw $e;
            }
            $created = true;
        }
        $row = Db::name(self::BINDING_TABLE)->where('id', $id)->find();
        if (!is_array($row)
            || (int)$row['tenant_id'] !== $tenantId
            || (int)$row['hotel_id'] !== $hotelId
            || !hash_equals($conversationHash, (string)$row['conversation_id_hash'])
        ) {
            throw new RuntimeException('企业微信入站绑定保存后回读失败');
        }
        $result = $this->publicBinding($row);
        $result['created'] = $created;
        $result['persistence_status'] = 'readback_verified';
        return $result;
    }

    /** @param list<int> $hotelIds @return array<string,mixed> */
    public function bindings(int $tenantId, array $hotelIds): array
    {
        $this->assertTablesReady();
        $hotelIds = $this->hotelIds($hotelIds);
        $query = Db::name(self::BINDING_TABLE)->whereIn('hotel_id', $hotelIds);
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        $rows = $query->order('id', 'desc')->limit(100)->select()->toArray();
        return ['list' => array_map([$this, 'publicBinding'], $rows), 'count' => count($rows)];
    }

    /** @param list<int> $hotelIds @return array<string,mixed> */
    public function events(int $tenantId, array $hotelIds, ?int $hotelId = null, int $limit = 50): array
    {
        $this->assertTablesReady();
        $hotelIds = $this->hotelIds($hotelIds);
        $query = Db::name(self::EVENT_TABLE)->whereIn('hotel_id', $hotelIds);
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        if ($hotelId !== null && $hotelId > 0) {
            $query->where('hotel_id', $hotelId);
        }
        $rows = $query->order('id', 'desc')->limit(max(1, min(100, $limit)))->select()->toArray();
        $list = [];
        foreach ($rows as $row) {
            $item = $this->normalizeEvent($row);
            $this->assertEventDigest($item);
            $list[] = $item;
        }
        return ['list' => $list, 'count' => count($list)];
    }

    /** @param list<int> $hotelIds @return array<string,mixed> */
    public function readEvent(int $id, int $tenantId, array $hotelIds): array
    {
        $this->assertTablesReady();
        $hotelIds = $this->hotelIds($hotelIds);
        $query = Db::name(self::EVENT_TABLE)->where('id', $id)->whereIn('hotel_id', $hotelIds);
        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }
        $row = $query->find();
        if (!is_array($row)) {
            throw new RuntimeException('wecom inbound event not found', 404);
        }
        $event = $this->normalizeEvent($row);
        $this->assertEventDigest($event);
        return $event;
    }

    public function verifyCallbackUrl(
        string $bindingKey,
        string $timestamp,
        string $nonce,
        string $signature,
        string $encryptedEcho
    ): string {
        $config = $this->requireCallbackConfig();
        $binding = $this->callbackBinding($bindingKey, false);
        $this->assertTimestamp($timestamp, 900);
        $this->assertSignature($config['token'], $timestamp, $nonce, $encryptedEcho, $signature);
        $plain = $this->decrypt($encryptedEcho, $config['aes_key'], $config['corp_id']);
        Db::name(self::BINDING_TABLE)->where('id', (int)$binding['id'])->update([
            'status' => 'verified',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $plain;
    }

    /** @return array<string,mixed> */
    public function handleCallback(
        string $bindingKey,
        string $timestamp,
        string $nonce,
        string $signature,
        string $rawXml
    ): array {
        $config = $this->requireCallbackConfig();
        $binding = $this->callbackBinding($bindingKey, true);
        if (strlen($rawXml) === 0 || strlen($rawXml) > 262_144) {
            throw new InvalidArgumentException('企业微信回调体为空或超过限制');
        }
        $outer = $this->parseXml($rawXml);
        $encrypted = trim((string)($outer->Encrypt ?? ''));
        if ($encrypted === '') {
            throw new InvalidArgumentException('企业微信加密回调缺少 Encrypt');
        }
        $this->assertTimestamp($timestamp, 86_400);
        $this->assertSignature($config['token'], $timestamp, $nonce, $encrypted, $signature);
        $innerXml = $this->decrypt($encrypted, $config['aes_key'], $config['corp_id']);
        $message = $this->parseXml($innerXml);
        $conversationId = trim((string)($message->ChatId ?? $message->RoomId ?? $message->FromUserName ?? ''));
        if ($conversationId === ''
            || !hash_equals((string)$binding['conversation_id_hash'], $this->conversationHash($conversationId))
        ) {
            throw new RuntimeException('企业微信回调会话与酒店绑定不一致', 403);
        }
        $senderId = trim((string)($message->FromUserName ?? ''));
        $messageType = strtolower(trim((string)($message->MsgType ?? 'unknown')));
        $content = $messageType === 'text' ? $this->safeText((string)($message->Content ?? ''), 1000) : '';
        $occurredAt = $this->occurredAt((string)($message->CreateTime ?? ''));
        $externalEventId = trim((string)($message->MsgId ?? ''));
        if ($externalEventId === '') {
            $externalEventId = 'evt:' . substr(hash('sha256', implode('|', [
                $senderId,
                (string)($message->CreateTime ?? ''),
                $messageType,
                $encrypted,
            ])), 0, 80);
        }
        $externalEventId = mb_substr($externalEventId, 0, 191);
        $senderHash = hash('sha256', 'wecom-sender-v1|' . $senderId);
        $payloadDigest = $this->digest([
            'external_event_id' => $externalEventId,
            'message_type' => $messageType,
            'transport' => 'wecom_app_callback',
            'sender_id_hash' => $senderHash,
            'content_text' => $content !== '' ? $content : null,
            'occurred_at' => $occurredAt,
        ]);

        $existing = Db::name(self::EVENT_TABLE)
            ->where('binding_id', (int)$binding['id'])
            ->where('external_event_id', $externalEventId)
            ->find();

        $baseRecord = [
            'contract_version' => self::CONTRACT_VERSION,
            'binding_id' => (int)$binding['id'],
            'tenant_id' => (int)$binding['tenant_id'],
            'hotel_id' => (int)$binding['hotel_id'],
            'external_event_id' => $externalEventId,
            'payload_digest' => $payloadDigest,
            'occurred_at' => $occurredAt,
            'message_type' => $messageType,
            'transport' => 'wecom_app_callback',
            'sender_id_hash' => $senderHash,
            'content_text' => $content !== '' ? $content : null,
            'archive_status' => 'readback_verified',
            'processing_status' => 'pending',
            'block_code' => null,
            'answer' => [],
            'evidence_refs' => [],
            'delivery_status' => 'not_sent',
            'delivery_reference' => null,
        ];
        $now = date('Y-m-d H:i:s');
        $id = is_array($existing) ? (int)($existing['id'] ?? 0) : 0;
        if (is_array($existing)
            && !hash_equals((string)($existing['payload_digest'] ?? ''), $payloadDigest)
        ) {
            throw new RuntimeException('企业微信事件幂等键内容冲突', 409);
        }
        if (!is_array($existing)) {
            try {
                $id = (int)Db::name(self::EVENT_TABLE)->insertGetId([
                'binding_id' => $baseRecord['binding_id'],
                'tenant_id' => $baseRecord['tenant_id'],
                'hotel_id' => $baseRecord['hotel_id'],
                'external_event_id' => $externalEventId,
                'payload_digest' => $payloadDigest,
                'occurred_at' => $occurredAt,
                'message_type' => $messageType,
                'transport' => 'wecom_app_callback',
                'sender_id_hash' => $senderHash,
                'content_text' => $baseRecord['content_text'],
                'archive_status' => 'readback_verified',
                'processing_status' => 'pending',
                'processing_claim_token' => null,
                'processing_lease_expires_at' => null,
                'block_code' => null,
                'answer_json' => $this->encode([]),
                'evidence_refs_json' => $this->encode([]),
                'delivery_status' => 'not_sent',
                'delivery_reference' => null,
                'content_digest' => $this->digest($baseRecord),
                'created_at' => $now,
                'updated_at' => $now,
                ]);
            } catch (Throwable $e) {
                $concurrent = Db::name(self::EVENT_TABLE)
                    ->where('binding_id', (int)$binding['id'])
                    ->where('external_event_id', $externalEventId)
                    ->find();
                if (!is_array($concurrent) || !hash_equals((string)$concurrent['payload_digest'], $payloadDigest)) {
                    throw $e;
                }
                $id = (int)($concurrent['id'] ?? 0);
            }
        }
        if ($id <= 0) {
            throw new RuntimeException('企业微信入站事件归档失败');
        }
        $archived = $this->readEvent($id, (int)$binding['tenant_id'], [(int)$binding['hotel_id']]);
        if ((string)$archived['archive_status'] !== 'readback_verified') {
            throw new RuntimeException('企业微信入站事件归档后回读失败');
        }
        $claim = $this->claimEventForProcessing($id, $payloadDigest);
        if (($claim['terminal'] ?? false) === true) {
            $event = (array)($claim['event'] ?? []);
            $event['duplicate'] = true;
            $event['persistence_status'] = 'duplicate_readback_verified';
            return $event;
        }
        $claimToken = (string)($claim['claim_token'] ?? '');

        $answer = [];
        $processingStatus = 'blocked';
        $blockCode = $messageType === 'text' ? null : 'message_type_unsupported';
        if ($messageType === 'text' && $content !== '') {
            try {
                $answer = (new WechatMonitorInboundAdapter())->handleNormalizedEvent([
                    'transport' => 'wecom_app_callback',
                    'signature_verified' => true,
                    'hotel_binding_verified' => true,
                    'message_type' => 'text',
                    'hotel_id' => (int)$binding['hotel_id'],
                    'content' => $content,
                ]);
                $processingStatus = (string)($answer['status'] ?? '') === 'reply_ready' ? 'reply_ready' : 'blocked';
                $blockCode = $processingStatus === 'reply_ready' ? null : (string)($answer['code'] ?? 'query_blocked');
            } catch (Throwable) {
                $processingStatus = 'failed';
                $blockCode = 'query_answer_failed';
            }
        } elseif ($messageType === 'text') {
            $blockCode = 'message_content_empty';
        }
        $evidenceRefs = array_values(array_unique(array_filter(array_map(
            static fn(mixed $item): string => mb_substr(trim((string)$item), 0, 220),
            (array)($answer['sources'] ?? [])
        ))));
        $finalRecord = array_merge($baseRecord, [
            'processing_status' => $processingStatus,
            'block_code' => $blockCode,
            'answer' => $answer,
            'evidence_refs' => $evidenceRefs,
        ]);
        $affected = Db::name(self::EVENT_TABLE)
            ->where('id', $id)
            ->where('payload_digest', $payloadDigest)
            ->where('processing_status', 'processing')
            ->where('processing_claim_token', $claimToken)
            ->update([
            'processing_status' => $processingStatus,
            'processing_claim_token' => null,
            'processing_lease_expires_at' => null,
            'block_code' => $blockCode,
            'answer_json' => $this->encode($answer),
            'evidence_refs_json' => $this->encode($evidenceRefs),
            'content_digest' => $this->digest($finalRecord),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        if ($affected !== 1) {
            throw new RuntimeException('企业微信入站事件终态更新竞争校验失败', 409);
        }
        $terminalRow = Db::name(self::EVENT_TABLE)->where('id', $id)->find();
        if (!is_array($terminalRow)
            || (string)($terminalRow['processing_status'] ?? '') !== $processingStatus
            || trim((string)($terminalRow['processing_claim_token'] ?? '')) !== ''
            || trim((string)($terminalRow['processing_lease_expires_at'] ?? '')) !== ''
        ) {
            throw new RuntimeException('企业微信入站事件终态保存后回读失败');
        }
        $event = $this->readEvent($id, (int)$binding['tenant_id'], [(int)$binding['hotel_id']]);
        if ((string)($event['processing_status'] ?? '') !== $processingStatus) {
            throw new RuntimeException('企业微信入站事件终态摘要回读失败');
        }
        $event['duplicate'] = false;
        $event['persistence_status'] = 'readback_verified';
        return $event;
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
            'boundaries' => [
                'raw_callback_persisted' => false,
                'sender_identifier_persisted' => false,
                'reply_delivery_recorded' => (string)($row['delivery_status'] ?? 'not_sent') === 'sent',
                'automatic_execution' => false,
                'ota_write' => false,
            ],
        ];
    }

    private function assertEventDigest(array $event): void
    {
        if (!hash_equals((string)$event['content_digest'], $this->eventDigest($event))) {
            throw new RuntimeException('企业微信入站事件回读摘要不一致');
        }
    }

    private function eventDigest(array $event): string
    {
        return $this->digest(array_intersect_key($event, array_flip([
            'contract_version', 'binding_id', 'tenant_id', 'hotel_id', 'external_event_id',
            'payload_digest', 'occurred_at', 'message_type', 'transport', 'sender_id_hash', 'content_text',
            'archive_status', 'processing_status', 'block_code', 'answer', 'evidence_refs', 'delivery_status',
            'delivery_reference',
        ])));
    }

    /** @return array{terminal:bool,event?:array<string,mixed>,claim_token?:string} */
    private function claimEventForProcessing(int $id, string $payloadDigest): array
    {
        return Db::transaction(function () use ($id, $payloadDigest): array {
            $row = Db::name(self::EVENT_TABLE)
                ->where('id', $id)
                ->where('payload_digest', $payloadDigest)
                ->lock(true)
                ->find();
            if (!is_array($row)) {
                throw new RuntimeException('企业微信入站事件占用前回读失败');
            }
            $event = $this->normalizeEvent($row);
            $this->assertEventDigest($event);
            $status = (string)($row['processing_status'] ?? '');
            if (in_array($status, self::TERMINAL_PROCESSING_STATUSES, true)) {
                return ['terminal' => true, 'event' => $event];
            }
            if (!in_array($status, ['pending', 'processing'], true)) {
                throw new RuntimeException('企业微信入站事件处理状态无法恢复', 409);
            }

            $activeToken = trim((string)($row['processing_claim_token'] ?? ''));
            $leaseTimestamp = strtotime(trim((string)($row['processing_lease_expires_at'] ?? ''))) ?: 0;
            if ($activeToken !== '' && $leaseTimestamp > time()) {
                throw new RuntimeException('企业微信入站事件正在处理，请稍后重试', 409);
            }

            $claimToken = hash('sha256', random_bytes(32));
            $leaseExpiresAt = date('Y-m-d H:i:s', time() + self::PROCESSING_LEASE_SECONDS);
            $event['processing_status'] = 'processing';
            $event['content_digest'] = $this->eventDigest($event);
            $affected = Db::name(self::EVENT_TABLE)
                ->where('id', $id)
                ->where('payload_digest', $payloadDigest)
                ->where('processing_status', $status)
                ->update([
                    'processing_status' => 'processing',
                    'processing_claim_token' => $claimToken,
                    'processing_lease_expires_at' => $leaseExpiresAt,
                    'content_digest' => (string)$event['content_digest'],
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            if ($affected !== 1) {
                throw new RuntimeException('企业微信入站事件占用竞争校验失败', 409);
            }
            $readback = Db::name(self::EVENT_TABLE)->where('id', $id)->find();
            if (!is_array($readback)
                || (string)($readback['processing_status'] ?? '') !== 'processing'
                || !hash_equals($claimToken, (string)($readback['processing_claim_token'] ?? ''))
                || (string)($readback['processing_lease_expires_at'] ?? '') !== $leaseExpiresAt
            ) {
                throw new RuntimeException('企业微信入站事件占用后回读失败');
            }
            $this->assertEventDigest($this->normalizeEvent($readback));

            return ['terminal' => false, 'claim_token' => $claimToken];
        });
    }

    /** @return array<string,mixed> */
    private function publicBinding(array $row): array
    {
        return [
            'id' => (int)($row['id'] ?? 0),
            'tenant_id' => (int)($row['tenant_id'] ?? 0),
            'hotel_id' => (int)($row['hotel_id'] ?? 0),
            'binding_key' => (string)($row['binding_key'] ?? ''),
            'label' => (string)($row['label'] ?? ''),
            'transport' => (string)($row['transport'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'reply_enabled' => (int)($row['reply_enabled'] ?? 0) === 1,
            'callback_path' => (string)($row['transport'] ?? '') === 'wecom_app_callback'
                ? '/api/integrations/wecom/callback/' . (string)($row['binding_key'] ?? '')
                : null,
            'conversation_id_stored_as_hash' => true,
            'created_by' => (int)($row['created_by'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    private function callbackBinding(string $bindingKey, bool $requireVerified): array
    {
        $this->assertTablesReady();
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $bindingKey) !== 1) {
            throw new InvalidArgumentException('企业微信回调 binding_key 无效');
        }
        $row = Db::name(self::BINDING_TABLE)
            ->where('binding_key', $bindingKey)
            ->where('transport', 'wecom_app_callback')
            ->find();
        $allowedStatuses = $requireVerified ? ['verified'] : ['pending_verification', 'verified'];
        if (!is_array($row) || !in_array((string)$row['status'], $allowedStatuses, true)) {
            throw new RuntimeException('企业微信回调绑定不存在或已停用', 404);
        }
        return $row;
    }

    /** @return array{token:string,aes_key:string,corp_id:string,public_base_url:string} */
    private function callbackConfig(): array
    {
        return [
            'token' => trim((string)env('WECOM_INBOUND_TOKEN', '')),
            'aes_key' => trim((string)env('WECOM_INBOUND_AES_KEY', '')),
            'corp_id' => trim((string)env('WECOM_INBOUND_CORP_ID', '')),
            'public_base_url' => rtrim(trim((string)env('WECOM_INBOUND_PUBLIC_BASE_URL', '')), '/'),
        ];
    }

    /** @return array{token:string,aes_key:string,corp_id:string,public_base_url:string} */
    private function requireCallbackConfig(): array
    {
        $config = $this->callbackConfig();
        if (!$this->configReady($config)) {
            throw new RuntimeException('企业微信自建应用回调尚未配置', 503);
        }
        return $config;
    }

    private function configReady(array $config): bool
    {
        return $config['token'] !== '' && strlen($config['aes_key']) === 43 && $config['corp_id'] !== '';
    }

    private function configError(
        array $config,
        string $schemaStatus,
        bool $cryptoReady,
        bool $publicHttpsReady,
        int $verifiedBindings
    ): string
    {
        if ($schemaStatus === DatabaseSchemaRequirement::STATUS_UNREADABLE) {
            return 'wecom_inbound_schema_unreadable';
        }
        if ($schemaStatus !== DatabaseSchemaRequirement::STATUS_PRESENT) {
            return 'wecom_inbound_tables_missing';
        }
        if (!$cryptoReady) {
            return 'wecom_crypto_runtime_missing';
        }
        if ($config['token'] === '' || strlen($config['aes_key']) !== 43 || $config['corp_id'] === '') {
            return 'wecom_callback_not_configured';
        }
        if (!$publicHttpsReady) {
            return 'wecom_public_https_callback_missing';
        }
        if ($verifiedBindings <= 0) {
            return 'wecom_verified_binding_missing';
        }
        return 'wecom_callback_unavailable';
    }

    private function assertSignature(string $token, string $timestamp, string $nonce, string $encrypted, string $signature): void
    {
        $parts = [$token, $timestamp, $nonce, $encrypted];
        sort($parts, SORT_STRING);
        $expected = sha1(implode('', $parts));
        if (!preg_match('/^[a-f0-9]{40}$/Di', $signature) || !hash_equals($expected, strtolower($signature))) {
            throw new RuntimeException('企业微信回调签名校验失败', 403);
        }
    }

    private function decrypt(string $encrypted, string $encodingAesKey, string $corpId): string
    {
        $key = base64_decode($encodingAesKey . '=', true);
        $cipher = base64_decode($encrypted, true);
        if (!is_string($key) || strlen($key) !== 32 || !is_string($cipher) || $cipher === '') {
            throw new RuntimeException('企业微信回调密文格式无效', 403);
        }
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($key, 0, 16));
        if (!is_string($plain) || $plain === '') {
            throw new RuntimeException('企业微信回调解密失败', 403);
        }
        $padding = ord($plain[strlen($plain) - 1]);
        if ($padding < 1 || $padding > 32 || substr($plain, -$padding) !== str_repeat(chr($padding), $padding)) {
            throw new RuntimeException('企业微信回调填充校验失败', 403);
        }
        $plain = substr($plain, 0, -$padding);
        if (strlen($plain) < 20) {
            throw new RuntimeException('企业微信回调解密内容过短', 403);
        }
        $length = unpack('Nlength', substr($plain, 16, 4));
        $messageLength = (int)($length['length'] ?? 0);
        if ($messageLength <= 0 || strlen($plain) < 20 + $messageLength) {
            throw new RuntimeException('企业微信回调消息长度无效', 403);
        }
        $message = substr($plain, 20, $messageLength);
        $receivedCorpId = substr($plain, 20 + $messageLength);
        if (!hash_equals($corpId, $receivedCorpId)) {
            throw new RuntimeException('企业微信回调 CorpID 校验失败', 403);
        }
        return $message;
    }

    private function parseXml(string $xml): SimpleXMLElement
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new InvalidArgumentException('企业微信回调XML包含不允许的声明');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $parsed = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$parsed instanceof SimpleXMLElement) {
            throw new InvalidArgumentException('企业微信回调XML无效');
        }
        return $parsed;
    }

    private function assertTimestamp(string $timestamp, int $maxSkewSeconds): void
    {
        if (preg_match('/^\d{10}$/D', $timestamp) !== 1 || abs(time() - (int)$timestamp) > $maxSkewSeconds) {
            throw new RuntimeException('企业微信回调时间戳无效或已过期', 403);
        }
    }

    private function occurredAt(string $timestamp): ?string
    {
        if (preg_match('/^\d{10}$/D', $timestamp) !== 1) {
            return null;
        }
        return gmdate('Y-m-d H:i:s', (int)$timestamp + 8 * 3600);
    }

    private function conversationHash(string $conversationId): string
    {
        return hash('sha256', 'wecom-conversation-v1|' . trim($conversationId));
    }

    /** @param list<int> $hotelIds @return list<int> */
    private function hotelIds(array $hotelIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $hotelIds), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            throw new InvalidArgumentException('企业微信入站查询缺少酒店范围');
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
        foreach ([self::BINDING_TABLE, self::EVENT_TABLE] as $table) {
            $statuses[] = DatabaseSchemaRequirement::inspectTable($table)['status'];
        }
        $eventColumns = DatabaseSchemaRequirement::inspectTableColumns(self::EVENT_TABLE);
        $statuses[] = $eventColumns['status'];
        if ($eventColumns['status'] === DatabaseSchemaRequirement::STATUS_PRESENT
            && array_diff(self::EVENT_PROCESSING_COLUMNS, $eventColumns['columns']) !== []
        ) {
            $statuses[] = DatabaseSchemaRequirement::STATUS_MISSING;
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
            throw new RuntimeException('企业微信入站归档表尚未迁移', 503);
        }
        if ($status !== DatabaseSchemaRequirement::STATUS_PRESENT) {
            throw new RuntimeException('企业微信入站归档表结构检查失败', 503);
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
