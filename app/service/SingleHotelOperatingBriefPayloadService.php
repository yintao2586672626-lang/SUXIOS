<?php
declare(strict_types=1);

namespace app\service;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Builds the target-independent WeCom payload candidate for the one-hotel pilot.
 *
 * This service reads verified same-day facts through the digest service. It
 * never resolves a robot, reads a webhook, persists a dispatch or sends data.
 */
final class SingleHotelOperatingBriefPayloadService
{
    public function __construct(
        private readonly ?SingleHotelOperatingDigestService $digest = null,
        private readonly ?SingleHotelOperatingBriefService $brief = null
    ) {
    }

    /** @return array<string,mixed> */
    public function pagePreview(
        int $tenantId,
        int $hotelId,
        string $hotelName,
        string $businessDate
    ): array {
        return $this->resolve(
            $tenantId,
            $hotelId,
            $hotelName,
            $businessDate,
            'page_preview',
            false
        );
    }

    /** @return array<string,mixed> */
    public function build(
        int $tenantId,
        int $hotelId,
        string $hotelName,
        string $businessDate,
        string $deliveryMode
    ): array {
        $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')))
            ->format('Y-m-d');
        if ($businessDate !== $today) {
            return $this->blocked(
                $tenantId,
                $hotelId,
                $businessDate,
                'base_operating_facts_business_date_not_today',
                '基础经营事实测试推送只允许使用上海时区当天数据。'
            );
        }

        return $this->resolve(
            $tenantId,
            $hotelId,
            $hotelName,
            $businessDate,
            $deliveryMode,
            true
        );
    }

    /** @return array<string,mixed> */
    private function resolve(
        int $tenantId,
        int $hotelId,
        string $hotelName,
        string $businessDate,
        string $deliveryMode,
        bool $forDelivery
    ): array {
        try {
            $digest = ($this->digest ?? new SingleHotelOperatingDigestService())
                ->build($tenantId, $hotelId, $businessDate, []);
            $brief = ($this->brief ?? new SingleHotelOperatingBriefService())
                ->preview($digest);
        } catch (\Throwable) {
            return $this->blocked(
                $tenantId,
                $hotelId,
                $businessDate,
                'base_operating_facts_read_failed',
                '基础经营事实读取失败，未使用0、旧数据或默认值代替。'
            );
        }

        $allowed = ($digest['applies'] ?? false) === true
            && ($digest['base_delivery_allowed'] ?? $digest['delivery_allowed'] ?? false) === true
            && (string)($brief['status'] ?? '') === 'preview_ready';
        $blockers = array_values(array_filter(
            (array)($digest['blockers'] ?? []),
            'is_array'
        ));
        if (!$allowed && $blockers === []) {
            $blockers[] = [
                'code' => 'pms_base_fact_gate_blocked',
                'message' => '订单来了PMS身份、日期、质量、对账或回读证据未通过。',
            ];
        }

        $payload = $this->payload(
            (string)($brief['content'] ?? ''),
            $deliveryMode
        );
        $fingerprint = hash('sha256', $this->json($payload));
        $gate = [
            'allowed' => $allowed,
            'status' => $allowed
                ? 'base_operating_facts_send_allowed'
                : 'base_operating_facts_send_blocked',
            'blockers' => $blockers,
            'required_source' => 'dingdandao_pms_same_hotel_same_date_verified',
            'target_module_required' => false,
            'ota_modules_required' => false,
        ];

        return [
            'status' => $allowed ? 'ready' : 'blocked',
            'reason_code' => $allowed
                ? 'base_operating_facts_ready'
                : 'pms_base_fact_gate_blocked',
            'business_date' => $businessDate,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'hotel_name' => $hotelName,
            'message_mode' => 'base_operating_facts',
            'operating_target_status' => 'not_enabled',
            'operating_target_record_id' => 0,
            'snapshot_revision_no' => 0,
            'optional_channel_status' => (array)($digest['optional_source_status'] ?? []),
            'preview_fingerprint' => $fingerprint,
            'formal_send_gate' => $gate,
            'base_fact_gate' => $gate,
            'digest' => $digest,
            'brief' => $brief,
            'payload' => $forDelivery && !$allowed ? null : $payload,
            'preview_only' => !$forDelivery,
            'message_sent' => false,
            'webhook_read' => false,
        ];
    }

    /** @return array{msgtype:string,markdown:array{content:string}} */
    private function payload(string $briefContent, string $deliveryMode): array
    {
        $modeLine = match ($deliveryMode) {
            'immediate_test' => '> 【测试】企业微信测试群单次推送；正式群未授权',
            'scheduled_test' => '> 【测试】企业微信测试群定时推送；正式群未授权',
            default => '> 当前模式：页面预览，未发送',
        };
        $lines = explode("\n", trim($briefContent));
        if ($lines === ['']) {
            $lines = ['# 宿析OS｜敦煌漠蓝新经营事实简报'];
        }
        array_splice($lines, 1, 0, [$modeLine]);

        return [
            'msgtype' => 'markdown',
            'markdown' => [
                'content' => mb_strcut(implode("\n", $lines), 0, 3900, 'UTF-8'),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function blocked(
        int $tenantId,
        int $hotelId,
        string $businessDate,
        string $code,
        string $message
    ): array {
        $gate = [
            'allowed' => false,
            'status' => 'base_operating_facts_send_blocked',
            'blockers' => [['code' => $code, 'message' => $message]],
            'required_source' => 'dingdandao_pms_same_hotel_same_date_verified',
            'target_module_required' => false,
            'ota_modules_required' => false,
        ];

        return [
            'status' => 'blocked',
            'reason_code' => $code,
            'business_date' => $businessDate,
            'tenant_id' => $tenantId,
            'hotel_id' => $hotelId,
            'message_mode' => 'base_operating_facts',
            'operating_target_status' => 'not_enabled',
            'operating_target_record_id' => 0,
            'snapshot_revision_no' => 0,
            'optional_channel_status' => [],
            'preview_fingerprint' => hash(
                'sha256',
                $hotelId . '|' . $businessDate . '|' . $code
            ),
            'formal_send_gate' => $gate,
            'base_fact_gate' => $gate,
            'payload' => null,
            'preview_only' => true,
            'message_sent' => false,
            'webhook_read' => false,
        ];
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
