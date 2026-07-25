<?php
declare(strict_types=1);

namespace app\service;

/**
 * Boundary for a future WeCom custom-app callback.
 *
 * A group robot Webhook is intentionally rejected here: it can send messages
 * but cannot deliver group replies to HOTEL. Signature verification,
 * decryption and group-to-hotel binding must happen before this adapter.
 */
final class WechatMonitorInboundAdapter
{
    /** @var callable|null */
    private $queryHandler;

    public function __construct(?callable $queryHandler = null)
    {
        $this->queryHandler = $queryHandler;
    }

    /** @return array<string, mixed> */
    public static function capability(): array
    {
        return [
            'robot_webhook_receive' => 'unsupported',
            'normalized_event_adapter' => 'ready',
            'external_status' => 'requires_wecom_custom_app_callback',
            'required_before_adapter' => [
                'callback_signature_verification',
                'callback_message_decryption',
                'verified_group_to_hotel_binding',
            ],
        ];
    }

    /** @param array<string, mixed> $event @return array<string, mixed> */
    public function handleNormalizedEvent(array $event): array
    {
        if ((string)($event['transport'] ?? '') !== 'wecom_app_callback') {
            return $this->blocked('send_only_robot_cannot_receive', '现有群机器人 Webhook 只能发送，不能接收群回复。');
        }
        if (($event['signature_verified'] ?? false) !== true) {
            return $this->blocked('callback_signature_unverified', '企业微信回调尚未完成验签与解密。');
        }
        if (($event['hotel_binding_verified'] ?? false) !== true) {
            return $this->blocked('hotel_binding_unverified', '回调群聊尚未完成门店绑定核验。');
        }
        if ((string)($event['message_type'] ?? '') !== 'text') {
            return $this->blocked('message_type_unsupported', '当前经营追问适配器只处理文本消息。');
        }

        $hotelId = (int)($event['hotel_id'] ?? 0);
        $content = trim((string)($event['content'] ?? ''));
        if ($hotelId <= 0 || $content === '') {
            return $this->blocked('normalized_event_invalid', '标准化事件缺少门店或文本内容。');
        }

        $answer = $this->queryHandler !== null
            ? call_user_func($this->queryHandler, $hotelId, $content)
            : (new WechatMonitorQueryService())->answer($hotelId, $content);
        if (!is_array($answer)) {
            return $this->blocked('query_answer_invalid', '经营追问服务未返回有效结果。');
        }

        return [
            'status' => 'reply_ready',
            'delivery_status' => 'not_sent',
            'outbound_transport_required' => true,
            'hotel_id' => $hotelId,
            'intent' => (string)($answer['intent'] ?? 'unknown'),
            'reply_text' => (string)($answer['reply_text'] ?? ''),
            'metric_scope' => (string)($answer['metric_scope'] ?? 'ota_channel'),
            'data_gaps' => (array)($answer['data_gaps'] ?? []),
            'sources' => (array)($answer['sources'] ?? []),
        ];
    }

    /** @return array<string, mixed> */
    private function blocked(string $code, string $message): array
    {
        return [
            'status' => 'blocked',
            'delivery_status' => 'not_sent',
            'code' => $code,
            'message' => $message,
        ];
    }
}
