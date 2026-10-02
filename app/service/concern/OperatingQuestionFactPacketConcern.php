<?php
declare(strict_types=1);

namespace app\service\concern;

use RuntimeException;

trait OperatingQuestionFactPacketConcern
{
    /** Reject explicit contradictions while preserving legacy packets without optional identity fields. */
    private function assertFactPacket(array $facts, array $scope, bool $requireVerified = true): void
    {
        foreach ($facts as $fact) {
            if (!is_array($fact)) {
                throw new RuntimeException('operating_question_fact_packet_invalid: 经营事实结构无效', 422);
            }
            foreach (['tenant_id' => 'tenant_id', 'system_hotel_id' => 'hotel_id'] as $field => $scopeField) {
                if (array_key_exists($field, $fact)
                    && ((!is_int($fact[$field]) && !is_string($fact[$field]))
                        || !ctype_digit((string)$fact[$field])
                        || (int)$fact[$field] <= 0
                        || (int)$fact[$field] !== (int)($scope[$scopeField] ?? 0))
                ) {
                    throw new RuntimeException('operating_question_fact_packet_invalid: 经营事实酒店或租户范围不一致', 422);
                }
            }
            $factPlatform = strtolower(trim((string)($fact['platform'] ?? '')));
            if ($factPlatform === '') {
                $factPlatform = strtolower(trim((string)($fact['source'] ?? '')));
            }
            $scopePlatform = (string)($scope['platform'] ?? '');
            if ($factPlatform !== ''
                && ($scopePlatform === 'all_ota'
                    ? !in_array($factPlatform, self::ALL_OTA_REQUIRED_PLATFORMS, true)
                    : $factPlatform !== $scopePlatform)
            ) {
                throw new RuntimeException('operating_question_fact_packet_invalid: 经营事实平台与请求范围不一致', 422);
            }
            foreach (['history_status' => ['success'], 'validation_status' => ['verified', 'derived_verified'],
                'quality_status' => ['verified', 'derived_verified'], 'readback_status' => ['readback_verified']]
                as $field => $expected) {
                if ($requireVerified && array_key_exists($field, $fact)
                    && (!is_string($fact[$field]) || !in_array(strtolower(trim($fact[$field])), $expected, true))
                ) {
                    throw new RuntimeException('operating_question_fact_packet_invalid: 经营事实尚未通过严格回读', 422);
                }
            }
            if ($requireVerified && array_key_exists('readback_verified', $fact)
                && !in_array($fact['readback_verified'], [true, 1, '1'], true)
            ) {
                throw new RuntimeException('operating_question_fact_packet_invalid: 经营事实尚未通过严格回读', 422);
            }
            if (array_key_exists('data_date', $fact)) {
                $date = is_string($fact['data_date']) ? trim($fact['data_date']) : '';
                $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if ($parsed === false || $parsed->format('Y-m-d') !== $date
                    || $date < (string)($scope['date_start'] ?? '')
                    || $date > (string)($scope['date_end'] ?? '')
                ) {
                    throw new RuntimeException('operating_question_fact_packet_invalid: 经营事实业务日期与请求范围不一致', 422);
                }
            }
        }
    }

    private function isBlockedFactExplanation(array $answer): bool
    {
        $result = is_array($answer['precise_result'] ?? null) ? $answer['precise_result'] : [];
        $verification = strtolower(trim((string)($result['verification_status'] ?? '')));
        return ($answer['mode'] ?? '') === 'deterministic_precise_query'
            && ($answer['query_router']['contract_version'] ?? '') === 'suxi_precise_query_router.v1'
            && str_starts_with((string)($answer['status'] ?? ''), 'blocked_')
            && array_key_exists('value', $result) && $result['value'] === null
            && trim((string)($result['blocked_reason'] ?? '')) !== ''
            && $verification !== '' && !in_array($verification, ['verified', 'derived_verified'], true)
            && !empty($answer['data_gaps'])
            && ($answer['action_drafts'] ?? []) === [];
    }
}
